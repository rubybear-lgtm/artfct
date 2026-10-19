export const Type = {
  Object: (properties) => ({ type: "object", properties }),
  String: (options) => ({ type: "string", ...options }),
  Number: (options) => ({ type: "number", ...options }),
  Optional: (schema) => schema,
};
