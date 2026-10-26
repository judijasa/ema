<?php

namespace Ema\Cli;

/**
 * A user-facing CLI error: the message is printed to STDERR and the process
 * exits non-zero — the PHP equivalent of the bash `echo ... >&2; exit 1`
 * contract. Thrown by Args::parse and, in later phases, by command
 * implementations, and caught once at the Application boundary.
 */
final class Error extends \RuntimeException
{
}
