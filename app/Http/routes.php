<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::get('/', function () {
    return view('home');
});

Route::get('api/rss/{rssFeed}', 'RssController@feed');
Route::post('api/api-ai', 'RssController@apiAi');
Route::post('api/webhook', 'RssController@webhook');
Route::get('api/webhook', 'RssController@webhook');
Route::get('api/api-ai', 'RssController@apiAi');
Route::get('api/news/{query}', 'RSSController@getNews');
Route::post('api/emotion', 'RSSController@getEmotion');
Route::get('user/{id}', 'UserController@show');
Route::get('spotify', 'RssController@spotify');
Route::get('test', 'RssController@test');