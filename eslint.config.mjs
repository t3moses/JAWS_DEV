import globals from "globals";

export default [
    {
        files: ["public/app/js/**/*.js"],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType: "module",
            globals: {
                ...globals.browser
            }
        },
        rules: {
            "no-console":           "warn",
            "no-unused-vars":       ["error", { argsIgnorePattern: "^_" }],
            "eqeqeq":               "error",
            "no-var":               "error",
            "prefer-const":         "warn",
            "no-empty":             "warn",
            "no-duplicate-imports": "error"
        }
    }
];
