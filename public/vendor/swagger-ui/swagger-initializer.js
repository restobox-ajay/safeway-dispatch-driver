// Boots Swagger UI for the admin API docs page. Kept as an external file (not an inline <script>)
// so the page keeps working under a strict Content-Security-Policy. The spec URL comes from the
// container's data-spec-url attribute, so this file never hard-codes a route.
window.addEventListener('load', function () {
    var container = document.getElementById('swagger-ui');
    window.ui = SwaggerUIBundle({
        url: container.getAttribute('data-spec-url'),
        dom_id: '#swagger-ui',
        deepLinking: true,
        persistAuthorization: false,
        tryItOutEnabled: false,
        docExpansion: 'list',
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout'
    });
});
