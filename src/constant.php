<?php
declare(strict_types=1);

const WORKBUNNY_NAME = 'WorkBunny';
const WORKBUNNY_VERSION = '0.0.1';

/**
 * @see stream_get_transports
 * @link https://www.php.net/manual/zh/transports.inet.php
 */
const WORKBUNNY_TRANSPORT_UDP  = 'udp';
const WORKBUNNY_TRANSPORT_TCP  = 'tcp';
/**
 * @see stream_get_transports
 * @link https://www.php.net/manual/zh/transports.unix.php
 */
const WORKBUNNY_TRANSPORT_UDG  = 'udg';
const WORKBUNNY_TRANSPORT_UNIX = 'unix';

/**
 * Default backlog. Backlog is the maximum length of the queue of pending connections.
 */
const WORKBUNNY_DEFAULT_BACKLOG = 102400;
/**
 * Max udp package size.
 */
const WORKBUNNY_MAX_UDP_PACKAGE_SIZE = 65535;
