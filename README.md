# Teeworlds Stats
This is the modified and updated source code of www.teeworlds-stats.info

### Installing

This project is running with PHP 7.2+
Download this repository and install the dependencies like described in the Dependencies section

### Dependencies

All dependencies are managed by `composer` and `npm`.
Run `composer install` in the root directory (where the composer.lock is saved) to install all PHP dependencies
Run `npm install && gulp build` in the `web/assets/twstats/` directory to install all JavaScript dependencies and bundle them.

### Usage

You can further configure your installation with the `services.yml`.
You can enable/disable the caching of the templates and the compression of the html output.

## Running the tests

There are no tests yet, I'm going to migrate this project to Laravel and write tests when I'm done

## Development

Want to contribute? Great!

I'm always glad hearing about bugs or pull requests.

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details


## Credits

- Teele for the fetching of teeworlds server info http://code.teele.eu/twrequest
- Muhammad Usman for the design http://usman.it/free-responsive-admin-template
- TeeTac/Adrien Morvan for the base of the code