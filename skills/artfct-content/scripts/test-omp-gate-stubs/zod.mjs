/**
 * Minimal `pi.zod` stand-in for test-omp-gate.mjs. It records schema shapes
 * and validates only what the gate relies on: optional strings, bounded
 * numbers, string enums, and arrays of file records. Anything else passes
 * through, so a test gap here fails a case rather than silently passing it.
 */

function optional(wrapper) {
	return {
		...wrapper,
		_optional: true,
		optional() {
			return this;
		},
		describe(text) {
			return { ...this, description: text };
		},
		parse(value) {
			return checkValue(this, value, "");
		},
		safeParse(value) {
			try {
				return { success: true, data: checkValue(this, value, "") };
			} catch (error) {
				return { success: false, error };
			}
		},
	};
}

function base(type) {
	return {
		type,
		optional() {
			return optional(this);
		},
		describe(text) {
			return { ...this, description: text };
		},
		parse(value) {
			return checkValue(this, value, "");
		},
		safeParse(value) {
			try {
				return { success: true, data: checkValue(this, value, "") };
			} catch (error) {
				return { success: false, error };
			}
		},
	};
}

function checkValue(schema, value, path) {
	if (value === undefined || value === null) {
		if (value === null && !schema._nullish) {
			throw new Error((path || "value") + " must not be null");
		}

		if (!schema._optional && !schema._nullish) {
			throw new Error("missing required field: " + path);
		}

		return value === null ? null : undefined;
	}

	switch (schema.type) {
		case "string":
			if (typeof value !== "string") {
				throw new Error(path + " must be a string");
			}

			return value;
		case "number":
			if (typeof value !== "number" || Number.isNaN(value)) {
				throw new Error(path + " must be a number");
			}

			if (schema.min !== undefined && value < schema.min) {
				throw new Error(path + " below minimum");
			}

			if (schema.max !== undefined && value > schema.max) {
				throw new Error(path + " above maximum");
			}

			return value;
		case "enum":
			if (!schema.values.includes(value)) {
				throw new Error(path + " not in enum");
			}

			return value;
		case "array":
			if (!Array.isArray(value)) {
				throw new Error(path + " must be an array");
			}

			return value.map((item, i) => checkValue(schema.items, item, path + "[" + i + "]"));
		case "object": {
			if (!value || typeof value !== "object" || Array.isArray(value)) {
				throw new Error(path + " must be an object");
			}

			const out = {};

			for (const [key, field] of Object.entries(schema.shape)) {
				const checked = checkValue(field, value[key], path ? path + "." + key : key);

				if (checked !== undefined) {
					out[key] = checked;
				}
			}

			return out;
		}
		default:
			return value;
	}
}

function object(shape) {
	const schema = {
		...base("object"),
		shape,
	};

	return schema;
}

export const zod = {
	object,
	string: () => base("string"),
	number: () => ({
		...base("number"),
		min(min) {
			return { ...this, min };
		},
		max(max) {
			return { ...this, max };
		},
	}),
	array: (items) => ({ ...base("array"), items }),
	enum: (values) => ({ ...base("enum"), values }),
};
