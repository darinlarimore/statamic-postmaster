<html>
<body>
    <h1>{{ $subject }}</h1>
    <p>Hi {{ $first_name }} ({{ $email }})</p>
    <a href="https://example.com/article">Read the article</a>
    <a href="{{ $unsubscribe_url }}">Unsubscribe</a>
</body>
</html>
