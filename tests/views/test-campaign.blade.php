<html>
<body>
    <h1>{{ $subject }}</h1>
    <p>Hi {{ $first_name }} ({{ $email }})</p>
    <img src="{{ $site_url }}logo.png">
    <p>&copy; {{ $site_name }} {{ $year }}</p>
    <a href="https://example.com/article">Read the article</a>
    <a href="{{ $unsubscribe_url }}">Unsubscribe</a>
</body>
</html>
