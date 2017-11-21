let ClassicEditor = require('@ckeditor/ckeditor5-build-classic');

ClassicEditor
    .create( document.querySelector( '#editor'), {
        // The plugins are now passed directly to .create().
        plugins: [
        ],

        // So is the rest of the default configuration.
        toolbar: [
            'headings',
            'bold',
            'italic',
            'link',
            'bulletedList',
            'numberedList',
            'blockQuote',
            'undo',
            'redo'
        ],
        image: {
            toolbar: [
                'imageStyleFull',
                'imageStyleSide',
                '|',
                'imageTextAlternative'
            ]
        }
    } )
    .then( editor => {
        console.log( editor );
    } )
    .catch( error => {
        console.error( error );
    } );