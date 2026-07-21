<?php

namespace Anesda\CRM\SpeedPhone;

final class BusinessDayCalculator
{
    public function addBusinessDays(\DateTimeImmutable $start, int $days): \DateTimeImmutable
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('Die Zahl der Werktage darf nicht negativ sein.');
        }

        $date = $start;
        $added = 0;
        while ($added < $days) {
            $date = $date->modify('+1 day');
            $weekday = (int) $date->format('N');
            if ($weekday <= 5) {
                $added++;
            }
        }

        return $date;
    }
}

