<?php

namespace App\Http\Controllers\FlowTrack\Pages;

use App\Http\Controllers\Controller;
use App\Services\FlowTrack\PageResponseService;

abstract class AbstractPageController extends Controller
{
    public function __construct(protected readonly PageResponseService $pages) {}
}
