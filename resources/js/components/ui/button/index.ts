import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm text-[13px] font-medium tracking-[0.01em] transition-[color,background-color,border-color,box-shadow] duration-150 disabled:pointer-events-none disabled:opacity-45 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-3.5 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-[inset_0_1px_0_rgb(255_255_255/0.08)] hover:bg-primary-hover",
        destructive:
          "bg-destructive text-destructive-foreground hover:bg-destructive/90",
        "destructive-outline":
          "border border-destructive/40 bg-transparent text-destructive hover:bg-destructive/5",
        outline:
          "border border-input bg-card hover:bg-accent hover:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        ghost:
          "hover:bg-accent hover:text-accent-foreground",
        link: "text-accent-text underline decoration-accent-text/35 underline-offset-4 hover:decoration-accent-text",
      },
      size: {
        "default": "h-9 px-4 has-[>svg]:px-3.5",
        "sm": "h-[30px] gap-1.5 px-3 text-xs has-[>svg]:px-2.5",
        "lg": "h-11 px-6 text-[13.5px] has-[>svg]:px-5",
        "icon": "size-9",
        "icon-sm": "size-[30px]",
        "icon-lg": "size-11",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
