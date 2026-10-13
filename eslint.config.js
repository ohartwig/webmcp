"use strict";

const moselwalBase = require("@moselwal/eslint-config");

/**
 * ESLint flat config for webmcp's own scripts.
 * Resources/Private/JavaScript/{webmcp,webmcp-init}.js are the
 * hand-authored sources; Resources/Public/JavaScript is esbuild output.
 * Base rules come from @moselwal/eslint-config. No stylelint: this
 * repository ships no CSS/SCSS at all.
 */
module.exports = [
  { ignores: ["node_modules/**", "reports/**", "Resources/Public/**"] },
  ...moselwalBase,
  {
    // Globals this code reaches for but does not own, every one of them behind
    // a `typeof … !== 'undefined'` guard at the point of use:
    //
    //   klaro, Cookiebot  consent managers, whichever the site happens to load
    //   WebMCP            this repository's own class, from the bundle that
    //                     webmcp-init.js is loaded alongside
    //   module            the CommonJS escape hatch at the end of webmcp.js
    //
    // Declaring them is the accurate description. Without it `no-undef` reports
    // nine errors that are all the same thing: eslint cannot see a script tag.
    languageOptions: {
      globals: {
        klaro: "readonly",
        Cookiebot: "readonly",
        WebMCP: "readonly",
        module: "writable",
      },
    },
  },
];
