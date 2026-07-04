<?php

namespace Quiote\Telemetry\Dashboard;

use Quiote\Exception\QuioteException;

/**
 * Thrown by {@see HttpMessageParser} for anything outside the narrow OTLP/HTTP
 * shape the OTel PHP exporter sends (see that class's docblock). The receiver
 * treats this as "reject this connection with 400, log, move on" -- it must
 * never be allowed to crash the dashboard process.
 */
final class MalformedRequestException extends QuioteException
{
}
