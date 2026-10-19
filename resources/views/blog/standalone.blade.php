<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $post->metaTitle() }}</title>
<meta name="description" content="{{ $post->description }}">
@include('blog.standalone-css')
</head>
<?php /* The review document, not the site page: the shell, the table of contents
         and the `details.q` FAQ are what the linter scores and what the publish
         gate reads, so a draft is reviewed as the page it is about to become.
         `data-surface` is what arms the gate; `data-lint="skip"` is the draft's
         documented opt-out (front matter `lint: skip`). */ ?>
<body data-surface="{{ $post->surface }}" data-register="{{ $post->register }}" data-target="{{ $post->target }}"@if ($post->lintSkip) data-lint="skip"@endif>
<div class="wrap">

  @include('blog.masthead')

  <header>
    <p class="kicker">{{ $post->kicker() }}</p>
    <h1>{{ $post->title }}</h1>
    <p class="meta"><b>The artfct team</b> &middot; Last updated {{ $post->date }} &middot; {{ $readMinutes }} min</p>
    {!! $lead !!}
  </header>

@if ($toc !== [])
  <nav class="toc">
    <p>On this page</p>
    <ol>
@foreach ($toc as $entry)
      <li><a href="#{{ $entry['id'] }}">{{ $entry['heading'] }}</a></li>
@endforeach
    </ol>
  </nav>
@endif

  <main>
@foreach ($sections as $section)
@if ($section['kind'] === 'faq')
    <section class="faq">
      <h2>{{ $section['heading'] }}</h2>
@foreach ($section['items'] as $item)
      <details class="q"><summary>{{ $item['question'] }}</summary>{!! $item['answer'] !!}</details>
@endforeach
    </section>
@else
    <section class="{{ $section['limits'] ? 'limits' : 's' }}" id="{{ $section['id'] }}">
      <h2>{{ $section['heading'] }}</h2>
      {!! $section['html'] !!}
    </section>
@endif
@endforeach
  </main>

@include('blog.byline')

</div>
</body>
</html>
