@extends('flarum.forum::layouts.basic')

@section('title', $heading)

@section('content')
  <style>
    .detour-heading { font-size: 28px; margin: 0 0 12px; }
    .detour-message { white-space: pre-line; color: #555; }
    .detour-search { display: flex; gap: 8px; max-width: 420px; margin: 28px auto; }
    .detour-search .form-control { flex: 1; text-align: left; padding: 12px 16px; }
    .detour-search .button { padding: 12px 18px; }
    .detour-list { list-style: none; padding: 0; margin: 0 0 28px; text-align: left; }
    .detour-list li { padding: 10px 0; border-top: 1px solid #eee; }
    .detour-list-title { font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: #888; margin: 0 0 6px; text-align: left; }
  </style>

  <h1 class="detour-heading">{{ $heading }}</h1>
  <p class="detour-message">{{ $message }}</p>

  <form class="detour-search" action="{{ $home }}/" method="get" role="search">
    <input class="form-control" type="search" name="q" value="{{ $query }}" placeholder="{{ $translator->trans('ernestdefoe-detour.views.search_placeholder') }}" aria-label="{{ $translator->trans('ernestdefoe-detour.views.search_placeholder') }}">
    <button class="button" type="submit">{{ $translator->trans('ernestdefoe-detour.views.search_button') }}</button>
  </form>

  @if (count($suggestions))
    <p class="detour-list-title">{{ $translator->trans('ernestdefoe-detour.views.suggestions') }}</p>
    <ul class="detour-list">
      @foreach ($suggestions as $suggestion)
        <li><a href="{{ $suggestion['url'] }}">{{ $suggestion['title'] }}</a></li>
      @endforeach
    </ul>
  @endif

  <p><a href="{{ $home }}">{{ $translator->trans('core.views.error.not_found_return_link', ['forum' => $settings->get('forum_title')]) }}</a></p>
@endsection
