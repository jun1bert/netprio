import './bootstrap';
import Alpine from 'alpinejs';
import { registerUI, enhanceUI } from './ui';
window.Alpine=Alpine;
registerUI(Alpine);
Alpine.start();
enhanceUI();