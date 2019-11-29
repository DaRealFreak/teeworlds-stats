# Teeworlds Stats
![](https://github.com/DaRealFreak/teeworlds-stats/workflows/Composer/badge.svg) ![](https://github.com/DaRealFreak/teeworlds-stats/workflows/Theme/badge.svg)

This is a completely rewritten version of [www.teeworlds-stats.info](http://www.teeworlds-stats.info) (offline by now)  
You can find this project being used live on [teeworlds-stats.com](https://teeworlds-stats.com/)  

## Installing
This project is running with PHP 7.3+ using the PHP Framework [Laravel](https://laravel.com).
Download this repository and install the dependencies like described in the Dependencies section

## Requirements
for running the application following dependencies are being used and tested:
 - PHP 7.3
 - MariaDB 10.2

for compiling and generating the assets following dependencies are being used and tested:
 - Node 13.2
 - composer 1.9

Everything required is already prepared in the development environment using [ddev](https://www.ddev.com/).  
You can simply run `ddev start` in the root directory to start the development environment.

## Dependencies
All dependencies are managed by `composer` and `npm`.

For installing all Backend dependencies you can run `composer install` in the root directory.  
For installing all Frontend dependencies you can run `npm install && npm run production`.

## Running the tests
There are no tests yet, I'm still at migrating this project to Laravel and write tests when I'm working at the corresponding code. 

## Development
Want to contribute? Great!  
I'm always glad hearing about bugs or pull requests.

## License
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Credits
- [Teele](http://code.teele.eu/twrequest) for the initial Teeworlds Server Info request (superseded by now)
- [TeeTac/Adrien Morvan](https://www.teeworlds.com/forum/profile.php?id=119481) for the initial idea causing me to migrate this to Laravel
