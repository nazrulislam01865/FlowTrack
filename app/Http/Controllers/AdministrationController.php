<?php
namespace App\Http\Controllers;
use Illuminate\View\View;
class AdministrationController extends Controller { public function __invoke(): View { return view('pages.administration'); } }
