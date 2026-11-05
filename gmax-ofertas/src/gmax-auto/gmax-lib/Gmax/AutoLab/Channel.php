<?php
declare(strict_types=1);

namespace Gmax\AutoLab;

use Gmax\SendResult;

/** Canal de homologação do AutoLab: só o supergrupo autorizado e só textos rotulados como teste. */
interface Channel
{
    /**
     * Consulta somente leitura do destino (getMe, getChat, getChatMemberCount, getChatMember do bot).
     * @return array{ok:bool,error:?string,chat_id?:string,type?:string,title?:string,public_username?:bool,members?:int,bot_status?:string,http?:int}
     */
    public function probe(string $chatId): array;

    public function send(string $chatId, string $text): SendResult;
}
