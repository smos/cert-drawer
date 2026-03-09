<?php

namespace App\Models;

use LdapRecord\Models\ActiveDirectory\User as AdUser;

class LdapUser extends AdUser
{
    /**
     * The attribute used to locate the user's email address.
     *
     * @var string
     */
    public static string $emailAttribute = 'mail';
}
