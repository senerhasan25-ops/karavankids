import './bootstrap';

// NOT: Alpine.js'i burada manuel başlatmıyoruz — Livewire 3 kendi içinde Alpine
// getirip otomatik başlatıyor. İki Alpine yüklenirse wire:click event handler'ları
// bağlanmıyor ve dropdown'daki Durdur butonları sessizce ölüyor.
