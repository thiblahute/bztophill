#!/usr/bin/env php
<?php

if (!getenv("PHABRICATOR_ROOT")) {
    echo "You must set the PHABRICATOR_ROOT environment variable to use this script\n";

    exit(1);
}

$root = getenv("PHABRICATOR_ROOT");
require_once $root.'/scripts/__init_script__.php';

if ($argc !== 2) {
  echo pht(
    "Usage: %s\n",
    'sent-welcome-mail.php <email>');
  exit(1);
}

function sendWelcomeEmail(PhabricatorUser $user) {
    $user_username = $user->getUserName();
    $base_uri = PhabricatorEnv::getProductionURI('/');

    $engine = new PhabricatorAuthSessionEngine();
    $uri = $engine->getOneTimeLoginURI(
        $user,
        $user->loadPrimaryEmail(),
        PhabricatorAuthSessionEngine::ONETIME_WELCOME);

    $body = pht(
        "Hello,\n\n".
        "The GStreamer and Pitivi projects are migrating their bug tracker from ".
        " gnome bugzilla[0] to Phabricator. In the migration process, an account has been".
        " automatically created for you on that phabricator instance so that you can be".
        " referenced on the bugs imported from bugzilla. The username for that account is '%s'".
        " but it can be changed asking an administrator to do so (contacts informations below).\n\n".
        "    You should now on exclusively use phabricator to report or comment any bug for the GStreamer project.".
        " To set a password for that new account, you can follow that link:\n\n".
        "  %s\n\n".
        "After you have set a password, you can login in the future by ".
        "going here:\n\n".
        "  %s\n\n".
        "For any question or request you can open a bug report at:\n".
        "    https://phabricator.freedesktop.org/project/view/22/".
        " or https://phabricator.freedesktop.org/tag/infrastructure/\n\n".
        " or contact me at: tsaunier@gnome.org\n\n".
        "Best regards,\n\nThibault Saunier on the behalf of the GStreamer team\n\n".
        "[0] https://bugzilla.gnome.org/\n",
        $user_username,
        $uri,
        $base_uri);

    $mail = id(new PhabricatorMetaMTAMail())
        ->addTos(array($user->getPHID()))
        ->setForceDelivery(true)
        ->setSubject(pht('[Phabricator] The GStreamer project created a Phabricator account for you during the process of migrating its bug tracker system to it.'))
        ->setBody($body)
        ->saveAndSend();
}

$email = $argv[1];
$user = id(new PhabricatorUser())->loadOneWithEmailAddress($email);
if (!$user) {
  throw new Exception( pht( "There is no user with the email '%s'!", $email));
}

sendWelcomeEmail($user);

echo pht("Sent mail to  user %s.\n", $email);
