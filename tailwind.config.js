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
            "lato": ["Lato", "sans-serif"]
        },
        fontSize: {
            "2xs": "10px"
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

