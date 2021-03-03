<?php

namespace Spartan\Console\Helper;

/**
 * Terminal Console
 *
 * @package Spartan\Console
 * @author  Iulian N. <iulian@spartanphp.com>
 * @license LICENSE MIT
 */
class Terminal
{
    /**
     * Prepare stty for input
     *
     * @return void
     */
    public static function prepare()
    {
        `stty sane`;
        `stty cbreak`;
    }

    /**
     * Disable echo in terminal
     *
     * @return void
     */
    public static function disableEcho()
    {
        `stty -echo`;
    }

    /**
     * Enable echo in terminal
     *
     * @return void
     */
    public static function enableEcho()
    {
        `stty echo`;
    }

    /**
     * Reset terminal
     *
     * @return void
     */
    public static function reset()
    {
        `stty sane`;
    }

    /**
     * @return string
     */
    public static function clearLine()
    {
        return "\e[K";
    }

    /**
     * Move cursor up
     *
     * @param int $lines Number of lines to move
     *
     * @return string
     */
    public static function cursorUp($lines = 1)
    {
        return "\e[{$lines}A";
    }

    /**
     * @param int $lines Number of lines to move
     *
     * @return string
     */
    public static function cursorDown($lines = 1)
    {
        return "\e[{$lines}B";
    }

    /**
     * Move cursor to start of line
     *
     * @return string
     */
    public static function cursorToLineStart()
    {
        return "\r";
    }

    /**
     * Hide cursor
     *
     * @return string
     */
    public static function cursorHide()
    {
        return "\e[?25l";
    }

    /**
     * Show cursor
     *
     * @return string
     */
    public static function cursorShow()
    {
        return "\e[?25h";
    }
}
