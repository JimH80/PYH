<?php

declare(strict_types=1);

namespace PYH\Security;

enum DataScope: string
{
    case None = 'none';
    case Own = 'scope.own';
    case Location = 'scope.location';
    case Organisation = 'scope.organisation';
}
