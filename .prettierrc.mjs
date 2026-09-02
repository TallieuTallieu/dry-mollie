/** @type {import("prettier").Config} */
export default {
    plugins: ['@prettier/plugin-php'],
    trailingComma: 'all',
    tabWidth: 4,
    semi: true,
    singleQuote: true,
    overrides: [
        {
            files: ['*.json', '*.yml', '*.yaml'],
            options: {
                tabWidth: 2,
            },
        },
    ],
};
