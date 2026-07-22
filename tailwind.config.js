module.exports = {
    content: ['./resources/**/*.blade.php', './resources/**/*.css'],
    corePlugins: {
        // Never reset the control panel's own styles.
        preflight: false,
    },
    // Prefix every utility so the addon can't clash with Statamic's CP classes.
    prefix: 'es-',
    // Match the CP's dark mode, which toggles a `dark` class on <html>.
    darkMode: ['variant', ['html[class*="dark"] &']],
    theme: {
        extend: {},
    },
}
