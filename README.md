# Teeworlds Stats
This is a completely rewritten version of www.teeworlds-stats.info

### Installing

This project is running with PHP 7.2+ using the PHP Framework [Laravel](https://laravel.com).
Download this repository and install the dependencies like described in the Dependencies section

### Dependencies

All dependencies are managed by `composer` and `npm`.

Run `composer install` in the root directory (where the [composer.lock](composer.lock) is saved) to install all PHP dependencies

Run `npm install && gulp build` in the [resources/assets/twstats/](resources/assets/twstats) directory to install all JavaScript dependencies and bundle them.

## Running the tests

There are no tests yet, I'm still at migrating this project to Laravel and write tests when I'm working at the corresponding code. 

## Development

Want to contribute? Great!

I'm always glad hearing about bugs or pull requests.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details

## Credits

- [Laravel for the awesome framework](https://laravel.com)
- [Teele for the fetching of teeworlds server info](http://code.teele.eu/twrequest)
- [TeeTac/Adrien Morvan for the base of the code](https://www.teeworlds.com/forum/profile.php?id=119481)