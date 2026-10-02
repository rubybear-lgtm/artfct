<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'mail.default' => 'cloudflare',
        'mail.from' => ['address' => 'hello@mail.artfct.dev', 'name' => 'artfct'],
        'services.cloudflare_email' => ['account_id' => 'acct123', 'api_token' => 'cf-token'],
    ]);
});

test('mail_is_sent_through_the_cloudflare_email_api', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    Mail::raw('You are invited', fn ($message) => $message->to('teammate@example.com')->subject('Join the team'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudflare.com/client/v4/accounts/acct123/email/sending/send'
        && $request->hasHeader('Authorization', 'Bearer cf-token')
        && $request['from']['address'] === 'hello@mail.artfct.dev'
        && $request['to'] === ['teammate@example.com']
        && $request['subject'] === 'Join the team'
        && $request['text'] === 'You are invited');
});

test('a_rejected_send_raises_so_the_queue_retries', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'domain not verified']]], 400)]);

    expect(fn () => Mail::raw('x', fn ($message) => $message->to('a@example.com')->subject('s')))
        ->toThrow(Exception::class, 'domain not verified');
});
