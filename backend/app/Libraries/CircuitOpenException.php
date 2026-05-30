<?php

namespace App\Libraries;

/**
 * CircuitOpenException — di-throw oleh `CircuitBreaker::call()` saat circuit
 * dalam state OPEN (service dianggap down, masih dalam cooldown window).
 *
 * Caller pattern:
 *
 *   try {
 *       $cb->call(fn() => $client->send($payload));
 *   } catch (CircuitOpenException $e) {
 *       // Skip silently / queue ulang / fallback ke degraded mode
 *   } catch (\Throwable $e) {
 *       // Failure asli dari service (timeout, 5xx, dll.)
 *       // CircuitBreaker sudah meng-increment failure counter
 *   }
 *
 * Subclass `RuntimeException` supaya bisa di-catch generik kalau caller
 * tidak peduli sumbernya.
 */
class CircuitOpenException extends \RuntimeException
{
}