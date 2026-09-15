<?php
/**
 * swich_ipn.php
 * Server-to-server Webhook / IPN listener for Swich Payment Gateway.
 * Matches the exact URL registered with Swich Support: https://arenareserve.app/swich_ipn.php
 */

require_once __DIR__ . '/swich_callback.php';
