<?php

// Ausschließlich für isolierte Tests: Diese Klasse öffnet niemals eine Netzwerkverbindung.
#[AllowDynamicProperties]
class SugarPHPMailer
{
    public static array $messages = [];
    public array $attachments = [];
    public function setMailerForSystem() {}
    public function ClearAllRecipients() {}
    public function ClearReplyTos() {}
    public function AddAddress($address) { $this->recipient = $address; }
    public function isHTML($enabled) {}
    public function AddStringAttachment($content, $filename, $encoding, $mime) { $this->attachments[] = $filename; }
    public function prepForOutbound() {}
    public function Send() { self::$messages[] = clone $this; return true; }
}
