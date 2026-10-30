<?php
declare(strict_types=1);

namespace Gmax\OAuth;

/** O provedor recusou as credenciais/refresh token: é preciso autorizar de novo. */
final class OAuthDenied extends \RuntimeException
{
}
