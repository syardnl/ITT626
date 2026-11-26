<?php

namespace App\Enums;

/** Public Comments reach the reporter; Internal Comments stay with the management office. */
enum CommentVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';
}
