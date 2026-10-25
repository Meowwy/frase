/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./resources/**/*.blade.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
    ],

    theme: {
    extend: {
        colors: {
            "black": '#060606',
            orange: {
                800: '#ff6f61',
                700: '#d7574d'
            },
        },
        fontFamily: {
            "hanken-grotesk": ["Hanken Grotesk", "sans-serif"],
            "lato": ['"Twemoji Country Flags"', "Lato", "sans-serif"]
        },
        fontSize: {
            "2xs": "10px"
        },
        // Below lg, on a page without the tab bar: what's left of the screen once the
        // layout has taken the mobile top bar (h-14 plus the notch inset), main's mt-4 and
        // the body's bottom padding (1.5rem plus the home-indicator inset), see html-layout.
        // An app-like screen whose own bottom bar starts at the bottom edge. dvh, not vh,
        // so it follows the browser's collapsing toolbars.
        minHeight: {
            "app-screen": "calc(100dvh - 6rem - env(safe-area-inset-top) - env(safe-area-inset-bottom))"
        }
    },
  },
    variants:{
        extend: {
            borderColor: ['hover'],
        }
    },
  plugins: [],
}

