<?php

class BMSkillReserve extends BMSkill {
    public static $hooked_methods = array('doesSkipSwingRequest');

    public static function doesSkipSwingRequest() {
        return 'doesSkipSwingRequest';
    }
}
