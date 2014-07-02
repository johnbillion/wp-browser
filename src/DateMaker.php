<?php

namespace tad\wordpress\maker;


class DateMaker
{
    const DATE_FORMAT = 'Y-m-d G:i:s';

    public static function now()
    {
        return date(self::DATE_FORMAT);
    }

    public static function gmnow()
    {
        return gmdate(self::DATE_FORMAT);
    }

    public static function zero()
    {
        return '0000-00-00 00:00:00';
    }
} 