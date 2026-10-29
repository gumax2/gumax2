<?php
declare(strict_types=1);

namespace Gmax;

interface Transport
{
    public function send(string $chatId, string $text): SendResult;
}
