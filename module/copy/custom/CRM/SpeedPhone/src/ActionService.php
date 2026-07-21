<?php

namespace Anesda\CRM\SpeedPhone;

final class ActionService
{
    public function __construct(
        private readonly Config $config,
        private readonly QueueService $queue,
        private readonly EmailService $emailService,
        private readonly BusinessDayCalculator $businessDays,
        private readonly \User $currentUser,
        private readonly LockService $locks
    ) {
    }

    public function execute(array $input): array
    {
        $validator = new InputValidator();
        $prospectId = $validator->uuid((string) ($input['prospect_id'] ?? ''));
        $lockToken = (string) ($input['lock_token'] ?? '');
        $action = $validator->action((string) ($input['result'] ?? ''));
        $newEmail = $validator->email((string) ($input['new_email'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        $emailRequested = !empty($input['email_requested']);

        if (!$this->queue->canEditProspect($prospectId) || !\ACLController::checkAccess('Prospects', 'edit', true)) {
            throw new \RuntimeException('Kein Zugriff auf diesen Zielkontakt.');
        }
        $this->locks->assertOwned($prospectId, $lockToken);

        /** @var \Prospect $prospect */
        $prospect = \BeanFactory::getBean('Prospects', $prospectId);
        if (!$prospect || empty($prospect->id) || (int) $prospect->deleted === 1) {
            throw new \RuntimeException('Der Zielkontakt wurde nicht gefunden.');
        }

        if ($newEmail !== '') {
            $prospect->email1 = $newEmail;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $attempts = (int) ($prospect->speedphone_attempts_c ?? 0);
        $nextCall = null;
        $status = '';
        $message = '';

        // Erst vollständig validieren, bevor ein regulärer CRM-Anruf angelegt wird.
        if ($action === 'callback') {
            $nextCall = $this->parseCallback((string) ($input['callback_at'] ?? ''));
        }

        if ($action !== 'later') {
            $attempts++;
            $this->createHeldCall($prospect, $action, $note, $emailRequested, $now);
        }

        switch ($action) {
            case 'not_reached':
                $maxAttempts = max(1, (int) $this->config->get('max_attempts', 5));
                if ($attempts >= $maxAttempts) {
                    $status = 'paused';
                    $message = 'Maximale Versuchszahl erreicht; der Kontakt wurde zur manuellen Prüfung pausiert.';
                } else {
                    $status = 'retry';
                    $retryDays = array_values((array) $this->config->get('retry_days', [2, 4, 7, 14, 30]));
                    $days = (int) ($retryDays[min($attempts - 1, count($retryDays) - 1)] ?? 2);
                    $nextCall = $this->businessDays->addBusinessDays($now, $days)->setTime(9, 0);
                    $message = 'Nicht erreicht; der Kontakt wurde weiter hinten erneut eingeplant.';
                }
                break;

            case 'callback':
                $status = 'callback';
                $this->createPlannedCallback($prospect, $note, $nextCall);
                $message = 'Rückruf wurde als geplanter Anruf gespeichert.';
                break;

            case 'interested':
                $status = 'interested';
                $message = 'Interesse wurde protokolliert; der Kontakt ist aus der Telefonliste entfernt.';
                break;

            case 'no_interest':
                $status = 'no_interest';
                $message = 'Kein Interesse wurde protokolliert; der Kontakt ist abgeschlossen.';
                break;

            case 'wrong_number':
                $status = 'invalid_phone';
                $message = 'Die Telefonnummer wurde als falsch markiert.';
                break;

            case 'blocked':
                $status = 'blocked';
                $prospect->do_not_call = 1;
                $message = 'Der Zielkontakt wurde dauerhaft für Anrufe gesperrt.';
                break;

            case 'later':
                $status = 'retry';
                $nextCall = $this->businessDays->addBusinessDays($now, 1)->setTime(9, 0);
                $message = 'Der Kontakt wurde ohne Anruf auf den nächsten Werktag verschoben.';
                break;
        }

        $prospect->speedphone_status_c = $status;
        $prospect->speedphone_attempts_c = $attempts;
        $prospect->speedphone_next_call_c = $nextCall?->format('Y-m-d H:i:s') ?? '';
        $prospect->speedphone_last_call_c = $action === 'later' ? ($prospect->speedphone_last_call_c ?? '') : $now->format('Y-m-d H:i:s');
        $prospect->speedphone_last_result_c = $action;
        $prospect->speedphone_last_note_c = $note;
        $prospect->save(false);

        $emailResult = null;
        if ($action === 'interested' && $emailRequested) {
            try {
                $emailResult = $this->emailService->sendRequestedInformation($prospect);
            } catch (\Throwable $exception) {
                $GLOBALS['log']->error('CRM SpeedPhone: E-Mail-Versand fehlgeschlagen: ' . $exception->getMessage());
                $emailResult = [
                    'sent' => false,
                    'message' => 'Der Anruf wurde gespeichert, aber die E-Mail konnte nicht versendet werden: '
                        . $exception->getMessage(),
                ];
            }
        }

        $this->locks->release($prospectId, $lockToken);

        return [
            'message' => $message,
            'status' => $status,
            'email' => $emailResult,
        ];
    }

    private function createHeldCall(
        \Prospect $prospect,
        string $result,
        string $note,
        bool $emailRequested,
        \DateTimeImmutable $now
    ): void {
        $labels = [
            'not_reached' => 'Nicht erreicht',
            'callback' => 'Rückruf vereinbart',
            'interested' => 'Interesse',
            'no_interest' => 'Kein Interesse',
            'wrong_number' => 'Falsche Nummer',
            'blocked' => 'Nicht mehr kontaktieren',
        ];
        /** @var \Call $call */
        $call = \BeanFactory::newBean('Calls');
        $call->name = 'SpeedPhone: ' . ($labels[$result] ?? $result);
        $call->description = $note;
        $call->status = 'Held';
        $call->direction = 'Outbound';
        $call->date_start = $now->format('Y-m-d H:i:s');
        $call->duration_hours = 0;
        $call->duration_minutes = 0;
        $call->parent_type = 'Prospects';
        $call->parent_id = $prospect->id;
        $call->assigned_user_id = $this->currentUser->id;
        $call->speedphone_result_c = $result;
        $call->speedphone_email_requested_c = $emailRequested ? 1 : 0;
        $call->save(false);
        if ($call->load_relationship('users')) {
            $call->users->add($this->currentUser->id);
        }
    }

    private function createPlannedCallback(\Prospect $prospect, string $note, \DateTimeImmutable $when): void
    {
        /** @var \Call $call */
        $call = \BeanFactory::newBean('Calls');
        $call->name = 'SpeedPhone: Rückruf ' . ($prospect->account_name ?: $prospect->last_name);
        $call->description = $note;
        $call->status = 'Planned';
        $call->direction = 'Outbound';
        $call->date_start = $when->format('Y-m-d H:i:s');
        $call->duration_hours = 0;
        $call->duration_minutes = 15;
        $call->parent_type = 'Prospects';
        $call->parent_id = $prospect->id;
        $call->assigned_user_id = $this->currentUser->id;
        $call->speedphone_result_c = 'callback';
        $call->save(false);
        if ($call->load_relationship('users')) {
            $call->users->add($this->currentUser->id);
        }
    }

    private function parseCallback(string $value): \DateTimeImmutable
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Für einen Rückruf sind Datum und Uhrzeit erforderlich.');
        }
        $timezoneName = (string) ($this->currentUser->getPreference('timezone') ?: 'Europe/Berlin');
        $timezone = new \DateTimeZone($timezoneName);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $timezone);
        if (!$date) {
            throw new \InvalidArgumentException('Das Rückrufdatum ist ungültig.');
        }
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        if ($date <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new \InvalidArgumentException('Der Rückruf muss in der Zukunft liegen.');
        }

        return $date;
    }
}
