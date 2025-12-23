import preset from '../../../../vendor/filament/support/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        // Esta es la ruta exacta de tu vista personalizada
        './resources/views/filament/infolists/components/activity-content-entry.blade.php',
    ],
}