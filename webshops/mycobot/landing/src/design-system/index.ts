// The Franchise Buddy design system — 26 components in 6 groups.
// Ported from the Claude Design handoff bundle (The Franchise Buddy Design System).
// Styling is authored against the CSS custom properties in ./tokens; import
// ./styles.css once at the app root.

export { Icon, ICON_BASE, type IconProps } from './components/core/Icon';
export { Button, type ButtonProps } from './components/core/Button';
export { IconButton, type IconButtonProps } from './components/core/IconButton';
export { Card, type CardProps } from './components/core/Card';
export { Badge, type BadgeProps } from './components/core/Badge';
export { Tag, type TagProps } from './components/core/Tag';
export { StatTile, type StatTileProps } from './components/core/StatTile';

export { Input, type InputProps } from './components/forms/Input';
export { Textarea, type TextareaProps } from './components/forms/Textarea';
export { Select, type SelectProps, type SelectOption } from './components/forms/Select';
export { Checkbox, type CheckboxProps } from './components/forms/Checkbox';
export { Radio, type RadioProps } from './components/forms/Radio';
export { Switch, type SwitchProps } from './components/forms/Switch';
export { FileDrop, type FileDropProps, type FileDropItem } from './components/forms/FileDrop';

export { Tabs, type TabsProps, type TabItem } from './components/navigation/Tabs';
export { SidebarNav, type SidebarNavProps, type SidebarItem, type SidebarSection } from './components/navigation/SidebarNav';
export { LanguageSwitcher, TFB_LANGUAGES, type LanguageSwitcherProps, type TfbLanguage } from './components/navigation/LanguageSwitcher';

export { Carousel, type CarouselProps, type CarouselItem } from './components/content/Carousel';
export { ModuleCard, type ModuleCardProps } from './components/content/ModuleCard';
export { PlanCard, type PlanCardProps } from './components/content/PlanCard';

export { DataTable, type DataTableProps, type DataTableColumn, type DataTableSort } from './components/data/DataTable';

export { Dialog, type DialogProps } from './components/feedback/Dialog';
export { Toast, type ToastProps } from './components/feedback/Toast';
export { Tooltip, type TooltipProps } from './components/feedback/Tooltip';
export { ProgressBar, type ProgressBarProps } from './components/feedback/ProgressBar';
