// Identity passthrough so Blade {{ }} interpolation can sit inside otherwise-valid JS in the
// inline view scripts: e.g. `blade({{ $someJson }})` parses, lints and ships the value unchanged.
window.blade = function blade<T>(value: T): T {
    return value;
};

export {};
