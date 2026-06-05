<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/docs', 'docs')->name('docs');
Route::inertia('/blog', 'blog')->name('blog');
