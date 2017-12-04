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
let fs = require('fs');
let babelify = require('babelify');
let path = require('path');
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

gulp.task('browserifyTest', ['scripts'], function() {
    return browserify({
        debug: true,
        //extensions: ['es6'],
        //entries: ['src/test.es6']
        entries: ['public/js/all.min.js']
    }).transform(babelify.configure({
        //extensions: ['es6'],
        sourceMapRelative: path.resolve(__dirname, 'public')
    }))
        .bundle()
        .pipe(fs.createWriteStream("public/bundle.js"));
});

// Watch Files For Changes
gulp.task('watch', function () {
    gulp.watch('js/*.js', ['lint', 'scripts', ['browserify']]);
    gulp.watch('scss/*.scss', ['sass']);
    gulp.watch('css/*.css', ['concatCss', 'cleanCSS']);
});

// Default Task
gulp.task('default', ['sass', 'concatCss', 'cleanCSS', 'scripts', 'browserify', 'watch']);