(function (global) {
  "use strict";

  const z = global.Zod || (global.zod && (global.zod.z || global.zod));

  const parseDateOnly = (value) => {
    if (!value) return null;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return null;
    date.setHours(0, 0, 0, 0);
    return date;
  };

  const toFieldErrors = (issues) => {
    const fieldErrors = {};
    (issues || []).forEach((issue) => {
      const key = String((issue.path && issue.path[0]) || "form");
      if (!fieldErrors[key]) {
        fieldErrors[key] = issue.message || "Please review this field.";
      }
    });
    return fieldErrors;
  };

  const createFallbackResult = () => ({
    validateTourBooking(values) {
      return { success: true, data: values || {}, errors: {} };
    },
    validateHotelBooking(values) {
      return { success: true, data: values || {}, errors: {} };
    }
  });

  if (!z || typeof z.object !== "function") {
    global.BookingValidationSchemas = Object.assign(
      {},
      global.BookingValidationSchemas || {},
      createFallbackResult(),
      { hasZod: false }
    );
    return;
  }

  const tourBookingSchema = z
    .object({
      booking_type: z.string().trim().min(1, "Please select a booking type."),
      package_name: z.string().optional().default(""),
      selected_locations: z.array(z.string().min(1)).default([]),
      tour_type: z.string().trim().min(1, "Please select a tour type."),
      jump_off_port: z.string().trim().min(1, "Please select a jump-off port."),
      booking_date: z.string().trim().min(1, "Please select a booking date."),
      booking_end_date: z.string().optional().default(""),
      tour_duration: z.string().trim().min(1, "Please select a valid date range to compute duration."),
      contact_number: z
        .string()
        .trim()
        .min(1, "Please enter your contact number.")
        .refine((val) => /^[0-9+\-\s()]{7,20}$/.test(val), "Please enter a valid contact number."),
      num_adults: z.coerce.number().int().min(0, "Adults cannot be negative."),
      num_children: z.coerce.number().int().min(0, "Children cannot be negative."),
      eco_category: z.string().trim().min(1, "Please select an environmental fee category."),
      payment_option: z.string().trim().min(1, "Please select a payment option."),
      agree_privacy: z.boolean(),
      agree_other_fees: z.boolean()
    })
    .superRefine((data, ctx) => {
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const selectedDate = parseDateOnly(data.booking_date);
      if (!selectedDate) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["booking_date"],
          message: "Please select a valid booking date."
        });
      } else if (selectedDate < today) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["booking_date"],
          message: "Please select a booking date that is today or later."
        });
      }

      const isService = data.booking_type === "boat" || data.booking_type === "tourguide";
      if (data.booking_type === "package" && !String(data.package_name || "").trim()) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["package_name"],
          message: "Please select a tour package before proceeding."
        });
      }

      if (isService && data.selected_locations.length < 1) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["selected_locations"],
          message: "Please select at least one location to visit."
        });
      }

      if (isService && data.selected_locations.length > 2) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["selected_locations"],
          message: "You can select up to two locations only."
        });
      }

      if (data.tour_type === "overnight") {
        const endDate = parseDateOnly(data.booking_end_date);
        if (!String(data.booking_end_date || "").trim()) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ["booking_end_date"],
            message: "Please select an end date for overnight booking."
          });
        } else if (!endDate || !selectedDate || endDate <= selectedDate) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ["booking_end_date"],
            message: "Please select an end date that is after the start date."
          });
        }
      }

      if (Number(data.num_adults) + Number(data.num_children) <= 0) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["num_adults"],
          message: "Please add at least one guest to continue."
        });
      }

      if (!data.agree_privacy) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["agree_privacy"],
          message: "Please agree to the Privacy Policy to continue."
        });
      }

      if (!data.agree_other_fees) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["agree_other_fees"],
          message: "Please acknowledge that additional fees may apply."
        });
      }
    });

  const hotelBookingSchema = z
    .object({
      checkin: z.string().trim().min(1, "Please select your check-in date."),
      checkout: z.string().trim().min(1, "Please select your complete stay duration."),
      adults: z.coerce.number().int().min(1, "Please enter at least one adult."),
      children: z.coerce.number().int().min(0, "Children cannot be negative."),
      payment_type: z
        .string()
        .trim()
        .refine((val) => val === "full" || val === "partial", "Please choose a payment option."),
      first_name: z.string().trim().min(1, "Please enter your first name."),
      last_name: z.string().trim().min(1, "Please enter your last name."),
      email: z.string().trim().min(1, "Please enter your email address.").email("Please enter a valid email address."),
      phone_number: z
        .string()
        .trim()
        .min(1, "Please enter your phone number.")
        .refine((val) => /^[0-9+\-\s()]{7,20}$/.test(val), "Please enter a valid phone number.")
    })
    .superRefine((data, ctx) => {
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const checkinDate = parseDateOnly(data.checkin);
      const checkoutDate = parseDateOnly(data.checkout);

      if (!checkinDate) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["checkin"],
          message: "Please select a valid check-in date."
        });
      } else if (checkinDate < today) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["checkin"],
          message: "Please select a check-in date that is today or later."
        });
      }

      if (!checkoutDate) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["checkout"],
          message: "Please select both dates in Stay Duration."
        });
      }

      if (checkinDate && checkoutDate && checkoutDate <= checkinDate) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["checkout"],
          message: "Please choose a Stay Duration ending after the check-in date."
        });
      }
    });

  const validateTourBooking = (values) => {
    const parsed = tourBookingSchema.safeParse(values || {});
    if (parsed.success) {
      return { success: true, data: parsed.data, errors: {} };
    }
    return { success: false, data: null, errors: toFieldErrors(parsed.error.issues) };
  };

  const validateHotelBooking = (values) => {
    const parsed = hotelBookingSchema.safeParse(values || {});
    if (parsed.success) {
      return { success: true, data: parsed.data, errors: {} };
    }
    return { success: false, data: null, errors: toFieldErrors(parsed.error.issues) };
  };

  global.BookingValidationSchemas = Object.assign(
    {},
    global.BookingValidationSchemas || {},
    {
      hasZod: true,
      validateTourBooking,
      validateHotelBooking
    }
  );
})(window);

