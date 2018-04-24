// Include gulp
let gulp = require('gulp');

// Include Our Plugins
let jshint = require('gulp-jshint');
let sass = require('gulp-sass');
let concat = require('gulp-concat');
// es6 version supported on gulp-uglify-es
let uglify = require('gulp-uglify-es').default;
let rename = require('gulp-rename');
let cleanCSS = require('gulp-clean-css');
let browserify = require('browserify');
let source = require('vinyl-source-stream');
let buffer = require('vinyl-buffer');
let concatCss = require('gulp-concat-css');

// Compile our Sass
gulp.task('sass', function () {
    return gulp.src('scss/*.scss')
        .pipe(sass())
        .pipe(gulp.dest('css'));
});

// Concat our CSS
gulp.task('concatCss', ['sass'], function () {
    return gulp.src('css/*.css')
        .pipe(concatCss("bundle.min.css"))
        .pipe(cleanCSS())
        .pipe(gulp.dest('public/css/bundle'));
});

// Clean our CSS
gulp.task('cleanCSS', function () {
    return gulp.src('css/*.css')
        .pipe(cleanCSS())
        .pipe(rename({
            suffix: '.min'
        }))
        .pipe(gulp.dest('public/css'));
});

// Concatenate & Minify JS
gulp.task('scripts', function () {
    return gulp.src('js/*.js')
        .pipe(concat('all.js'))
        .pipe(gulp.dest('public/js'))
        .pipe(rename('all.min.js'))
        .pipe(uglify())
        .pipe(gulp.dest('public/js'));
});

gulp.task('lint', function() {
    return gulp.src('js/*.js')
        .pipe(jshint())
        .pipe(jshint.reporter('jshint-stylish'));
});

/**
 * Browserify everything
 * Put everything which [require]
 * into the bundle.js
 **/
gulp.task('browserify', ['scripts'], function() {
    return browserify('public/js/all.min.js')
        .bundle()
        .pipe(source('bundle.js'))
        .pipe(buffer())
        //.pipe(uglify()) /*Did not worked */
        .pipe(gulp.dest('./public/js/bundle'));
});

gulp.task('copyFonts', function () {
    gulp.src('fonts/*')
        .pipe(gulp.dest('public/css/fonts'));
});

// Watch Files For Changes
gulp.task('watch', function () {
    gulp.watch('js/*.js', ['scripts', ['lint', 'browserify']]);
    gulp.watch('scss/*.scss', ['sass']);
    gulp.watch('css/*.css', ['concatCss', 'cleanCSS', 'copyFonts']);
});

// Default Task
gulp.task('default', ['watch']);

gulp.task('build', ['sass', 'concatCss', 'cleanCSS', 'copyFonts', 'lint', 'scripts', 'browserify']);