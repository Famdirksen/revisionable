<?php

namespace Famdirksen\Revisionable;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Arr;

/**
 * Class RevisionableTrait
 * @package Famdirksen\Revisionable
 */
trait ExceptionReportTrait
{
    private function reportException(\Exception $e) {
        if(function_exists('report')) {
            report($e);
        }
    }
}
