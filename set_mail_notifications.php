#!/usr/bin/env php
<?php

$root = getenv("PHABRICATOR_ROOT");
require_once $root.'/scripts/__init_script__.php';

if ($argc !== 4) {
  echo pht(
    "Usage: %s %s\n",
    "set_mail_notifications.php <email> <preference-type> <value>\n",
    "$argc args given");
  exit(1);
}

$email = $argv[1];
$type = $argv[2];
$value = $argv[3];

$user = id(new PhabricatorUser())->loadOneWithEmailAddress($email);
if (!$user) {
  echo pht("User $email does not exist, can not disable mails.")."\n";
  exit(1);
}

if ($type != PhabricatorUserPreferences::PREFERENCE_NO_SELF_MAIL &&
    $type != PhabricatorUserPreferences::PREFERENCE_NO_MAIL) {

  echo pht("preference-type should be in [" . PhabricatorUserPreferences::PREFERENCE_NO_SELF_MAIL. " ". PhabricatorUserPreferences::PREFERENCE_NO_MAIL. "], current value $type\n");
  exit(1);
}

echo "Check: ". PhabricatorUserPreferences::PREFERENCE_NO_SELF_MAIL. "\n";
$preferences = $user->loadPreferences();
$old_value = $preferences->getPreference($type);
$preferences->setPreference($type, $value);
$preferences->save();

$preferences = $user->loadPreferences();
echo "Set ". $type . "  from '$old_value' to '{$preferences->getPreference($type)}' for user: {$user->getUsername()}\n";
