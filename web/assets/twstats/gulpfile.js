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

// Compile our Sass
gulp.task('sass', function () {
    return gulp.src('scss/*.scss')
        .pipe(sass())
        .pipe(gulp.dest('public/css'));
});

// Clean our CSS
gulp.task('cleanCSS', function () {
    return gulp.src('css/*.css')
        .pipe(cleanCSS())
        .pipe(gulp.dest('public/css'));
});

// Concatenate & Minify JS
gulp.task('scripts', function () {
    return gulp.src('js/*.js')
        .pipe(concat('all.js'))
        .pipe(gulp.dest('public'))
        .pipe(rename('all.min.js'))
        .pipe(uglify())
        .pipe(gulp.dest('public/js'));
});

/**
 * Browserify everything
 * Putt everything which [require]
 * In bundle.js
 **/
gulp.task('browserify', function() {
    return browserify('public/js/all.min.js')
        .bundle()
        .pipe(source('bundle.js'))
        .pipe(buffer())
        //.pipe(uglify()) /*Did not worked */
        .pipe(gulp.dest('./public/'));
});

gulp.task('browserifyTest', function() {
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
});

// Default Task
gulp.task('default', ['sass', 'cleanCSS', 'scripts', 'browserify', 'watch']);