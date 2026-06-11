# Teeworlds Stats
![](https://github.com/DaRealFreak/teeworlds-stats/workflows/Composer/badge.svg) ![](https://github.com/DaRealFreak/teeworlds-stats/workflows/Theme/badge.svg)

This is a completely rewritten version of [www.teeworlds-stats.info](http://www.teeworlds-stats.info) (offline by now)  
You can find this project being used live on [teeworlds-stats.com](https://teeworlds-stats.com/)  

## Installing
This project runs on PHP 8.5 using the PHP Framework [Laravel](https://laravel.com).
Download this repository and install the dependencies like described in the Dependencies section.

## Requirements
For running the application the following dependencies are used and tested:
 - PHP 8.5
 - MariaDB 11.8

For compiling and generating the assets the following dependencies are used and tested:
 - Node 22
 - Composer 2

Everything required is already prepared in the development environment using [ddev](https://www.ddev.com/).  
You can simply run `ddev start` in the root directory to start the development environment.

## Dependencies
All dependencies are managed by `composer` and `npm`.

For installing all backend dependencies you can run `composer install` in the root directory.  
For installing and building all frontend dependencies you can run `npm install && npm run build`.

## Running the tests
The test suite uses PHPUnit with an in-memory SQLite database.

Inside the DDEV container:
```
vendor/bin/phpunit
```

From the host:
```
ddev exec vendor/bin/phpunit
```

## Development
Want to contribute? Great!  
I'm always glad hearing about bugs or pull requests.

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits
- [Teele](http://code.teele.eu/twrequest) for the initial Teeworlds Server Info request (superseded by now)
- [TeeTac/Adrien Morvan](https://www.teeworlds.com/forum/profile.php?id=119481) for the initial idea causing me to migrate this to Laravel
