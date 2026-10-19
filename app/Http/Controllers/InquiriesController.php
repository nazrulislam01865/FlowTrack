<?php
namespace App\Http\Controllers;
use Illuminate\View\View;
class InquiriesController extends Controller { public function __invoke(): View { return view('pages.inquiries', ['title' => 'Inquiries']); } }
