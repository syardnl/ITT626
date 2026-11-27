<?php

namespace App\Enums;

enum AuditAction: string
{
    case SignedIn = 'auth.signed_in';
    case SignInFailed = 'auth.sign_in_failed';
    case PasswordSet = 'auth.password_set';
    case InviteSent = 'account.invite_sent';
    case Created = 'created';
    case Updated = 'updated';
    case Deactivated = 'deactivated';
    case Reactivated = 'reactivated';
    case Reviewed = 'complaint.reviewed';
    case Classified = 'complaint.classified';
}
